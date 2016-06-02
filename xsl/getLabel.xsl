<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
    xmlns:xlink="http://www.w3.org/1999/xlink" xmlns:mods="http://www.loc.gov/mods/v3"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" version="1.0">
    <xsl:output method="text" indent="yes" encoding="UTF-8"/>
    <xsl:template match="/mods:mods/mods:titleInfo">
        <xsl:if test="mods:nonSort">
            <xsl:value-of select="mods:nonSort"/>
        </xsl:if>
        <xsl:value-of select="mods:title"/>
        <xsl:if test="mods:subTitle">
            <xsl:text>: </xsl:text>
            <xsl:value-of select="mods:subTitle"/>
        </xsl:if>
    </xsl:template>
    <xsl:template match="text()"/>
</xsl:stylesheet>
